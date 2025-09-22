@extends('admin.common.master')
@section('content')
<div class="container-fluid" id="vc">
    <div class="row">
        <!-- <div class="form-group col-md-3">
            <button class="btn btn-primary mt-4" @click="delete_g8pad()">{{ __('刪除兩台平板帳號所有地圖') }}</button>
        </div> -->
        <div class="form-group col-md-3">
            <button class="btn btn-primary mt-4" @click="change_train1()">{{ __('外平板name5帳號刷新教學') }}</button>
        </div>
        <div class="form-group col-md-3">
            <button class="btn btn-primary mt-4" @click="change_train2()">{{ __('內平板我是誰帳號刷新教學') }}</button>
        </div>
        <div class="form-group col-md-3">
            <button class="btn btn-primary mt-4" @click="change_train3()">{{ __('重置GDData資料') }}</button>
        </div>
    </div>

</div>

<script type="text/javascript">
    var vc = new Vue({
        el:'#vc',
        data:{
            lists           : [],
            pageData        : false,
        },
        methods: {
            get(type = false, sort = false) {

            },
            delete_g8pad(){
                if(confirm("確認刪除?")){
                    vc = this;
                    let url = "{{ config('services.API_URL').'/user/delete_maps/94' }}"
                    $.ajax({
                        method: "POST",
                        url: url,
                        data: {},
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        },
                    });
                    url = "{{ config('services.API_URL').'/user/delete_maps/85' }}"
                    $.ajax({
                        method: "POST",
                        url: url,
                        data: {},
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        },
                    });
                    url = "{{ config('services.API_URL').'/user/delete_maps/98' }}"
                    $.ajax({
                        method: "POST",
                        url: url,
                        data: {},
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        },
                    });
                }
            },
            change_g8pad(){
                if(confirm("確認更新?")){
                    vc = this;
                    let url = "{{ config('services.API_URL').'/user/change_g8pad' }}"
                    $.ajax({
                        method: "POST",
                        url: url,
                        data: {},
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        },
                    });
                }
            },
            change_train1(){
                if(confirm("確認更新?")){
                    vc = this;
                    let url = "{{ config('services.API_URL').'/user/128' }}"
                    $.ajax({
                        method: "PUT",
                        url: url,
                        data: {
                            teaching_square:0,
                            teaching_level:0
                        },
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        },
                    });
                }
            },
            change_train2(){
                if(confirm("確認更新?")){
                    vc = this;
                    let url = "{{ config('services.API_URL').'/user/135' }}"
                    $.ajax({
                        method: "PUT",
                        url: url,
                        data: {
                            teaching_square:0,
                            teaching_level:0
                        },
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        },
                    });
                }
            },
            change_train3()
            {
                if(confirm("確認更新?")){
                    vc = this;
                    let url = "{{ config('services.API_URL').'/refresh_gddata_items' }}";
                    $.ajax({
                        method: "POST",
                        url: url,
                        data: {},
                        dataType: 'json',
                        success(data){
                            sNotify(data.message);
                        },
                        error:function(xhr, ajaxOptions, thrownError){
                            console.log(xhr);
                        }
                    });
                }
            }
        },
        created : function(){
            this.get();
        },
        mounted: function(){

        }
    });
</script>
@stop